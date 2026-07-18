# SCHEDULED-V3-DEPLOY.md — scheduled orders in v3 (the tail's last workflow)

## What this is
Sales → **Scheduled** (clock icon, badge = deliveries due within 24h). The queue of
orders parked for a future delivery time: KPIs (Scheduled · Due in 24h · **Overdue**
in red), every row deep-linking to the order's full salesOrder workspace.

Actions, all via the proven POST /papi/schedule-order:
- **Schedule an order** — pick from unscheduled orders + a date/time
- **Change a delivery time** — repick from the queue
- **Send now** — releases it into the normal flow immediately (mode:now)

The ProcessScheduled cron keeps advancing stages and reminders exactly as before —
this is the window onto it, not a new engine.

## Milestone: the workflow tail is now EMPTY
With this + consignments, every operational workflow has a v3 home. The retire
command's unmapped list is down to admin-only pages (audit, partners, billing,
diagnostics, onboard). `v3:retire 17` after the soak retires classic with zero
workflow loss.

## Files (8)
ScheduledOrdersCapability (NEW) · CoreCapabilityProvider · NavCountsCapability ·
shell.blade.php · ClassicPanelRedirects (+/panel/scheduled) · V3RetireCommand ·
config/guide.php (Scheduled card in the Sales chapter) · ScheduledWorkspaceTest (3 tests)

## Deploy
After the consignments zip (shared files here are strict supersets). Rebuild →
`php artisan test --filter='ScheduledWorkspace|ConsignmentWorkspace|ClassicRedirects'`
