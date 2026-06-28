<?php
use Illuminate\Support\Facades\Schedule;

// Drives scheduled-delivery reminders and due marketing campaigns.
// Requires the `scheduler` process (php artisan schedule:run every minute) — see HOW-TO §8d.
Schedule::command('shopbot:process-scheduled')->everyMinute()->withoutOverlapping();

// Smart-bot (n8n) shared, tenant-keyed schedules
Schedule::command('bot:watchdog')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('bot:digest')->hourly()->withoutOverlapping()->onOneServer();

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

// Customer delivery-confirmation nudge — 60+ min after Delivered, once, daytime only.
Schedule::command('orders:delivery-reminders')->everyFifteenMinutes()->between('8:00', '21:00')->withoutOverlapping()->onOneServer();

// Seller data-discipline watchdog — nudge on orders stuck in an active status past SLA.
Schedule::command('orders:staleness-watchdog')->everyThirtyMinutes()->between('8:00', '21:00')->withoutOverlapping()->onOneServer();
