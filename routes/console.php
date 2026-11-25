<?php
use Illuminate\Support\Facades\Schedule;

// Drives scheduled-delivery reminders and due marketing campaigns.
// Requires the `scheduler` process (php artisan schedule:run every minute) — see HOW-TO §8d.
Schedule::command('shopbot:process-scheduled')->everyMinute()->withoutOverlapping();

// Smart-bot (n8n) shared, tenant-keyed schedules
Schedule::command('bot:watchdog')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
// Missed-reply recovery: answer customers whose bot reply never got through (opt-in per business: bot_recover_missed).
Schedule::command('bot:recover-missed')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
// Travel enquiries (#145): retry staff alerts that were not delivered. A no-op when there are none.
Schedule::command('travel:retry-handoffs')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
// Travel (#145): delete travellers' passport / ID numbers after each agency's retention period.
Schedule::command('travel:purge-documents')->dailyAt('02:17')->withoutOverlapping()->onOneServer();
// Sales team (Internet-provider businesses): quotations past their validity date become expired.
Schedule::command('quotes:expire')->dailyAt('03:07')->withoutOverlapping()->onOneServer();
// Internet providers (DishNet, 18 Nov 2026): follow up open quotations the customer has not answered — day 2 and
// day 5, in the salesperson's name, only while the business is open by its own hours. Opt-in: isp_quote_followup.
Schedule::command('isp:quote-followup')->hourlyAt(17)->withoutOverlapping()->onOneServer();
Schedule::command('relationship:extract')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('relationship:learn')->dailyAt('02:30')->timezone('Africa/Nairobi')->withoutOverlapping()->onOneServer();
Schedule::command('demand:digest')->dailyAt('08:00')->timezone('Africa/Nairobi')->withoutOverlapping()->onOneServer();
// Supplier Pay Advisor — WhatsApp the owner who to pay (overdue balances), once + weekly. Advisory only,
// never pays. No-ops unless pay_advisor_enabled is ON and pay_advisor_notify_phone is set (preview-first).
Schedule::command('suppliers:pay-advisor')->dailyAt('08:20')->timezone('Africa/Nairobi')->withoutOverlapping()->onOneServer();
Schedule::command('relationship:autosend')->dailyAt('08:15')->timezone('Africa/Nairobi')->withoutOverlapping()->onOneServer();
Schedule::command('relationship:stages')->dailyAt('03:15')->timezone('Africa/Nairobi')->withoutOverlapping()->onOneServer();
Schedule::command('relationship:attribute')->dailyAt('06:50')->timezone('Africa/Nairobi')->withoutOverlapping()->onOneServer();
Schedule::command('relationship:plan')->dailyAt('07:30')->timezone('Africa/Nairobi')->withoutOverlapping()->onOneServer();
// Phase 1 — Customer Re-engagement Engine: build the daily "who to contact today" plan (NO sending).
// Runs after relationship:stages (03:15) so RFM health is fresh. No-ops unless engagement_enabled is ON.
Schedule::command('reengage:plan')->dailyAt('06:20')->timezone('Africa/Nairobi')->withoutOverlapping()->onOneServer();
// Phase 2b — PACED sender for the daily plan. Small batches, daytime-gated inside the command.
// No-ops unless BOTH engagement_enabled AND engagement_send_enabled are ON (the latter is OFF by default).
Schedule::command('reengage:send-tick')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
// Phase-0 Stock & Loss — resolve PENDING vision movement events to MATCH or escalate to UNRECONCILED.
// No-ops unless vision.reconcile.enabled is on.
Schedule::command('vision:reconcile-sweep')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('relationship:review')->monthlyOn(1, '08:00')->timezone('Africa/Nairobi')->withoutOverlapping()->onOneServer();
// Weekly WhatsApp to the owner: items customers asked for that we don't stock + bot gaps (purchasing signal).
Schedule::command('demand:weekly')->weeklyOn(1, '08:00')->timezone('Africa/Kampala')->withoutOverlapping()->onOneServer();
// v4.19 TenderBot — twice-daily sweep of the configured tender forum for enabled tenants.
Schedule::command('tenders:scan')->twiceDailyAt(7, 13, 0)->timezone('Africa/Nairobi')->withoutOverlapping()->onOneServer();
// v4.20 TenderBot — hourly deadline escalation (7d / 48h / 24h; each stage fires once per tender).
Schedule::command('tenders:remind')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('bot:digest')->hourly()->withoutOverlapping()->onOneServer();

// Reliability: catch dropped WhatsApp connections fast (a down number silently kills orders),
// nudge a reconnect, and arm the in-panel banner. Also watches Evolution capacity for self-serve growth.
Schedule::command('wa:watchdog')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
// A line that says "open" but receives messages late (DishNet, 24 Nov 2026: 7 hours) — alert, once per 2 hours.
Schedule::command('wa:delivery-check')->everyTenMinutes()->withoutOverlapping()->onOneServer();

// Trial + subscription lifecycle: reminders before a trial ends, expiry notice, lapsed-payment dunning.
// Notifications only — feature enforcement is already automatic via Tenant::effectivePlan().
Schedule::command('billing:lifecycle')->dailyAt('09:00')->withoutOverlapping()->onOneServer();

// Auto-learn tenants so discovery never has to be run by hand.
//  - Daily: onboard any tenant that has chats but no discovery yet (new tenants learn within a day).
//  - Weekly: full-history re-learn for every tenant so the Business Brain stays current.
// Run inline (--sync) so a single `php artisan schedule:work` process handles everything — no
// separate queue worker needed. onOneServer guards against double-runs if ever scaled out.
Schedule::command('discovery:auto --new-only --sync')->dailyAt('02:30')->withoutOverlapping(60)->onOneServer();
Schedule::command('discovery:auto --sync')->weeklyOn(0, '03:00')->withoutOverlapping(120)->onOneServer();

// Owner Knowledge Engine (Phase 1, observe mode)
//  - Conversational evening nudge: each tenant fires when its local nudge time passes (once/day).
//  - Projection parity: nightly diagnostic that the Daily Menu projection matches the knowledge store.
Schedule::command('oke:nudge')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('oke:parity')->dailyAt('02:00')->withoutOverlapping()->onOneServer();

// Order Engine — daily KPI line per tenant (shadow telemetry).
Schedule::command('orders:observatory')->dailyAt('06:00')->withoutOverlapping()->onOneServer();

// Morning cashbook digest — WhatsApp summary + emailed CSV of yesterday's cashbook to the owner.
// Opt-in per tenant via the `cashbook_digest_to` setting (blank = off). East Africa local time.
Schedule::command('cashbook:digest')->dailyAt('06:30')->timezone('Africa/Kampala')->withoutOverlapping()->onOneServer();

// Customer delivery-confirmation nudge — 60+ min after Delivered, once, daytime only.
Schedule::command('orders:delivery-reminders')->everyFifteenMinutes()->between('8:00', '21:00')->withoutOverlapping()->onOneServer();

// Seller data-discipline watchdog — nudge on orders stuck in an active status past SLA.
Schedule::command('orders:pending-digest')->everyThirtyMinutes()->between('8:00', '21:00')->withoutOverlapping()->onOneServer();

Schedule::command('credit:reminders')->dailyAt('09:00')->withoutOverlapping()->onOneServer();

Schedule::command('tasks:escalate')->everyFifteenMinutes()->between('8:00', '20:00')->withoutOverlapping()->onOneServer();
Schedule::command('ops:digest')->dailyAt('08:00')->withoutOverlapping()->onOneServer();
Schedule::command('ops:digest')->dailyAt('14:00')->withoutOverlapping()->onOneServer();

// Twice-daily WhatsApp task reminders to each ASSIGNEE — one consolidated digest per person (overdue
// first). Opt-in per tenant via the `task_reminders` setting. Morning + early-afternoon nudge, Juba local.
Schedule::command('tasks:remind')->dailyAt('09:00')->timezone('Africa/Juba')->withoutOverlapping()->onOneServer();
Schedule::command('tasks:remind')->dailyAt('14:00')->timezone('Africa/Juba')->withoutOverlapping()->onOneServer();

Schedule::command('bot:lead-followup')->everyFiveMinutes()->between('8:00', '20:00')->withoutOverlapping()->onOneServer();

// Sourcing: keep nudging the buyer (Jayesh) for unpriced requests until he replies the price.
Schedule::command('sourcing:remind')->hourly()->between('8:00', '20:00')->withoutOverlapping()->onOneServer();

// Storefront weekly digest — funnel + zero-result searches (unmet demand) to the owner.
// Opt-in per tenant via the `storefront_gaps_digest` setting (blank = off). Monday morning, EAT.
Schedule::command('storefront:gaps-digest')->weeklyOn(1, '08:00')->timezone('Africa/Kampala')->withoutOverlapping()->onOneServer();

// Virtual salesperson: enquiry follow-up cadence + reorder reminders (opt-in per tenant).
// Pinned to East Africa Time so "9am–7pm" is local (Kampala/Juba), regardless of server TZ.
Schedule::command('bot:sales-cadence')->hourly()->between('9:00', '19:00')->timezone('Africa/Kampala')->withoutOverlapping()->onOneServer();

// Communication Center: email queue heartbeat — sends due/scheduled/retry mail. With
// QUEUE_CONNECTION=sync this sweep IS the sender (no worker needed). Atomic claim => Horizon-safe.
Schedule::command('comms:email-sweep')->everyMinute()->withoutOverlapping()->onOneServer();

// Communication Center: campaign batches — one throttle-sized batch per due campaign per minute.
Schedule::command('comms:campaign-sweep')->everyMinute()->withoutOverlapping()->onOneServer();
// AI campaigns (v4.5): paced sender — 2 approved drafts per tenant per minute, daytime only.
Schedule::command('aic:send-tick')->everyMinute()->withoutOverlapping()->onOneServer();

// ─── Inventory Trust v1 ───────────────────────────────────────────────────────
// Daily owner digest + queue at 06:45 EAT (after cashbook:digest at 06:30). Tenant gate is the
// `trust_digest` setting, so no --tenant here — it sweeps every opted-in tenant.
Schedule::command('trust:daily --send')->dailyAt('06:45')->timezone('Africa/Kampala')->withoutOverlapping()->onOneServer();
// v1.2: fresh blind-count link to staff every morning, after the digest computed confidences.
// One line per pilot tenant (trust:links requires an explicit --tenant).
Schedule::command('trust:links --tenant=17 --count --send')->dailyAt('07:00')->timezone('Africa/Kampala')->withoutOverlapping()->onOneServer();
// v1.3: weekly shrink waterfall + pilot metrics, Monday 07:15 EAT. Archive the --json output weekly.
Schedule::command('trust:weekly --tenant=17 --send')->weeklyOn(1, '07:15')->timezone('Africa/Kampala')->withoutOverlapping()->onOneServer();

// v4.65.7 — Stock Ledger parity watchdog. Read-only; dormant until stock_v2 is ON for a tenant, so
// this is inert on every shop that has not enabled it.
//   Daily  — DRIFT AT HOME only: the ledger disagrees with the product screen, so a number somebody
//            is reading right now is wrong. Worth a message the same morning.
//   Weekly — also MISFILED STOCK: movements at a location the shop does not use. Real, inert, and
//            nothing reads them. Mentioning it once a week is right; nagging daily is how a watchdog
//            gets muted, and a muted watchdog is worse than none.
Schedule::command('stock:watch')->dailyAt('06:00')->timezone('Africa/Juba')->withoutOverlapping()->onOneServer();
Schedule::command('stock:watch --include-misfiled')->weeklyOn(1, '08:20')->timezone('Africa/Juba')->withoutOverlapping()->onOneServer();
