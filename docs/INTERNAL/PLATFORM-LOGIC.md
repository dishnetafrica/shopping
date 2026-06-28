# CloudBSS — Platform Logic (INTERNAL)

> **Confidential / team-only.** This documents *how the engine actually works* — the decision rules,
> thresholds, and guards that we deliberately keep out of the client-facing guide. It lives in the repo
> for our team. **Never serve or link this from the tenant panel.** There is no route to `docs/`, so it
> is not reachable by clients; keep it that way. The client guide (`resources/panel/guide.html`) is the
> only thing tenants see, and it describes *what* features do, never *how*.

---

## 1. Message pipeline
`app/Jobs/ProcessIncomingMessage.php` → `BotBrain::respond(Tenant, Conversation, string)` → (LLM path)
`AiBrain`.

- **Prices come only from the catalogue.** The model is told never to invent a price; quoting is done
  from our products via the pricer, then a clean confirmation is appended. This is the core
  anti-hallucination rule — do not loosen it.
- **Intent is classified before any catalogue match** (payment claims, support, FAQ, etc. are routed
  first; product search runs only when the message is a product request).
- **Order/quote protocol:** the model emits `<<ORDER>>` / `<<QUOTE>>` blocks; the app parses and prices
  them. `<<ORDER>>` items may carry an optional `grams` field.
- **WeightPricer** (`ProductWeightVariant`): exact variant → pro-rata → not-priceable. It **never
  upsizes** (won't substitute a larger pack to make a price). ORD-86 (weight pricing collapse) is the
  first replay case in `tests/Fixtures/order_replays.json` — every production bug becomes a replay.

## 2. Inventory confidence  (`app/Services/Inventory/InventoryState.php`)
Stateless service; one snapshot per product+location (`inventory_snapshots`).

- Confidence decays **by age of the observation**: `EXACT` for 12h (`EXACT_HOURS`), then `ESTIMATED`
  to 72h (`ESTIMATED_HOURS`), then `UNKNOWN`.
- `recordCount($p,$qty,$source)` → writes `EXACT`. The count screen calls this with source `panel`.
- `state()` returns `['state','qty','observed_at','source','line']`; `botTag()` is the compact tag fed
  to the AI catalogue so the model reflects confidence instead of inventing it
  ("in stock: N" / "usually available — confirm" / "stock unknown — confirm before promising").
- `decrementOnSale()` reduces a *known* count and keeps it fresh; unknown stays unknown.
- **Client guide says none of this** — only "counted items show availability; recount fast movers."

## 3. Human handover  (`AgentNudge` + `ProcessIncomingMessage` from_me block)
When a human sends a message (`from_me`):

- `App\Support\AgentNudge::is($text)` — a short greeting/ack ("hi", "ok", 👋) is a **nudge**; a
  substantive/price message is a **takeover**.
- Nudge (and not already engaged) → pause the bot for `human_nudge_minutes` (default 3; set 0 to resume
  immediately). Substantive → `human_takeover_minutes` (default 60).
- Pause is stored as `state['human_until'] = now + minutes*60`; the bot auto-resumes when it lapses.
- `BotTrace` logs `nudge` / `takeover`. Client guide hides the heuristic entirely.

## 4. Lead follow-up  (`bot:lead-followup`, `LeadFollowUpCommand`)
Re-engages a warm lead who asked then went quiet. **Governed (ADR-0002 reason-coded outbound):**

- Per-tenant **opt-in**: `reengage_followup.enabled` (default **false**).
- Config: `delay_min`(10), `max_min`(180), `require_lead`(true), `link`, `message`.
- Fires only if: quiet ≥ delay and ≤ max, **we already replied** (`last_message_at > last_inbound_at`),
  not `agent_active`, `human_until` lapsed, cart empty, not `state.do_not_contact`, and (by default) an
  **open `Lead`** exists for the phone. Needs a catalogue to send (link and/or `catalog_files`) or it
  skips — never an empty follow-up.
- **Once** per conversation via `state['catalogue_followup_at']`. Reason `CATALOGUE_FOLLOWUP`
  (MessageLog + BotTrace). Scheduled every 5 min, 08:00–20:00.
- UI: enable toggle goes through `botToggle` (special-cased to set the nested `.enabled`); delay/link/
  warm-lead saved via `reengage-save` → `reengageSave()`. Current values exposed in `settings()` as
  `reengage`.

## 5. Quotations
- `QuotationService::generate(Tenant,$phone,$name,$quote,?$no)` builds the PDF on the `public` disk at
  `storage/app/public/quotations/{tenant}/Quotation-{no}.pdf`.
- **Serving:** `quotationPdf()` streams it inline via `Storage::disk('public')->response(...)` — does
  **not** rely on the `/storage` symlink (EasyPanel drops it on redeploy) and regenerates from stored
  items if the file was wiped. List/show/resend return the relative `/papi/quotation-pdf?id=` URL.
  WhatsApp sends are unaffected (base64).
- Statuses: sent/accepted/declined/expired/converted/archived. The detail UI renders accepted+converted
  **read-only**, matching the 422 backend guards.

## 6. Orders & SLA
- Statuses New→…→Delivered→Customer confirmed (+ Cancelled/Rejected/archived). `status_changed_at` is
  **not cast** — parse with Carbon.
- `OrderSla::staleMinutes($vertical,$status,$overrides)` — e.g. manufacturer New ≈ 2 days,
  restaurant New = 15 min. Today's "Fix now" group reads this.
- `orders:pending-digest` (every 30 min, 08:00–21:00) is the owner digest. `orders:delivery-reminders`
  sends **one** confirm nudge after a per-tenant window measured **from the delivery event**
  (transport orders get a longer window). `orders:staleness-watchdog` is **neutralised (no-op)** — do
  not re-enable; it spammed per-order with a hardcoded 15-min New threshold.

## 7. Vertical-aware nudges  (`OkeNudgeCommand`)
Daily nudge templates per vertical (restaurant/grocery/snacks); **manufacturer → skip** (returns null).
Respects a custom template + the enabled flag. This is why Krishna/EuroPearl must have
`vertical=manufacturer` set, or they'll get the wrong prompt.

## 8. Today aggregator  (`PanelApiController::today()`)
Returns prioritised **task groups** (Fix now / Reply / Confirm delivery / Count stock / Win back /
Failed pipelines) — each a concrete entity + reason + one action. **No fabricated confidence %, no
"estimated minutes"** — honest basis only. `humanMins()` is the only relative-time helper.

## 9. Panel page pattern (for new screens)
`resources/panel/X.html` + `SellerPanelController::method()` (`brandize`, `Cache-Control: no-store`) +
`/panel/X` route + shared `window.CB.*` layer (`/panel/shared/{api,utils,modal}.js`, whitelisted, raw
JS, no brandize). Auth via `?token=`. The guide page additionally injects the tenant's vertical into
`<body data-vert>` so the client guide adapts copy without exposing logic.

## 10. Deploy reminders
- No migrations in the current change set.
- Scheduler must have `orders:pending-digest` + `bot:lead-followup`, **not** `orders:staleness-watchdog`.
- `php artisan storage:link` once if other `/storage` assets break (quote PDF no longer needs it).
- EasyPanel volume/overlay quirk: CLI and PHP-FPM can read different `routes/web.php` copies — only a
  **redeploy** propagates route changes.
