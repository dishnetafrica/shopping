# Task completion confirmations (WhatsApp)

When a manually-assigned task is completed — from the app, the no-login WhatsApp
link, or the panel's Done button — the system now sends two best-effort WhatsApps:

1. **Receipt to the person who completed it** — proof the system received it and
   logged it, echoing whatever note they left:
   > ✅ Got it! *Buy stock* (#7) is marked done and saved in Family Shoppers.
   > Your note: bought 5 boxes
   > Thank you — nothing more needed.

2. **Alert to your owner-alert number(s)** — who finished what, plus their note:
   > ✅ *Savan* completed *Buy stock* (#7).
   > Note: bought 5 boxes
   > Jul 28, 14:00 · logged in the portal.

If the person who completed it is also the owner-alert number, only one message
is sent (no duplicate).

## Scope & controls (keeps it from becoming noise)
- **Only manually-assigned (ad-hoc) tasks** trigger this — the "New task" ones you
  send out. Routine order-workflow stage completions do NOT notify.
- **On/off switch:** the tenant setting `task_done_alerts` (default ON). Set it to
  false to mute all completion alerts for a shop.
- Uses your existing WhatsApp connection and owner-alert number(s); nothing new to
  configure. If a shop has no WhatsApp linked, it's silently skipped.
- Best-effort: a messaging hiccup never blocks or reverses the completion itself.

## Files changed
- `app/Services/Ops/TaskEngine.php`
  New private `notifyCompleted()`; called from `complete()` (after commit),
  `completeByNonce()` (the link), and `setStatusById()` (panel Done, on the real
  open→completed transition only, so re-saving a done task never re-notifies).
- `tests/Feature/TaskCompletionAlertTest.php` (NEW) — regression tests: ad-hoc
  completion notifies both, link completion notifies, workflow completion does not,
  and the setting mutes it.

## Deploy
No migration. Just deploy the file and clear caches:

    php artisan config:clear
    php artisan route:clear
    php artisan view:clear

If you run a queue worker (WhatsApp sends are queued jobs), restart it so it picks
up the new code:  `php artisan queue:restart`

## Turning it off later
    php artisan tinker
    >>> $t = \App\Models\Tenant::where('slug','family-shoppers...')->first();
    >>> $t->putSetting('task_done_alerts', false);
