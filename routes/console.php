<?php
use Illuminate\Support\Facades\Schedule;

// Drives scheduled-delivery reminders and due marketing campaigns.
// Requires the `scheduler` process (php artisan schedule:run every minute) — see HOW-TO §8d.
Schedule::command('shopbot:process-scheduled')->everyMinute()->withoutOverlapping();

// Auto-learn tenants so discovery never has to be run by hand.
//  - Daily: onboard any tenant that has chats but no discovery yet (new tenants learn within a day).
//  - Weekly: full-history re-learn for every tenant so the Business Brain stays current.
// Both queue per-tenant AutoLearnTenant jobs (needs a running queue worker).
Schedule::command('discovery:auto --new-only')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('discovery:auto')->weeklyOn(0, '03:00')->withoutOverlapping();
