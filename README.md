# Task reminder link fix — custom domain + no-login actions

Two problems in the WhatsApp "Daily Jobs Update" flow are fixed here.

## Problem 1 — link used the raw app host, not familyshoppers.net
The reminder message linked to `https://evo-shopbot.1gk84r.easypanel.host/jobs/...`.
The reminder runs as a scheduled **console command**, which has no incoming HTTP
request, so `url()` falls back to `APP_URL` (the easypanel host) instead of the
tenant's own domain.

**Fix:** build the link with the existing `Tenant::publicUrl()` helper, which uses
the tenant's `custom_domain` when set (→ `https://familyshoppers.net/jobs/...`),
and only falls back to `url()` when no custom domain is configured.

- `app/Console/Commands/TaskReminderCommand.php` (line ~78)

## Problem 2 — could not close / update a task from the link (as an end user)
The per-task page (`/task/{nonce}`) posts complete/accept/comment/upload actions.
These routes run in Laravel's `web` group, so they were subject to **CSRF session
verification**. A public visitor opening the link on the custom domain has no
matching session cookie (cookie domain differs from the app host), so the token
comparison failed with a 419 and the "Mark as done" button silently did nothing.

**Fix:** exempt the nonce-authorized task endpoints from CSRF. They are already
authorized by an unguessable 16-char nonce + a 30/min rate limit — the session
CSRF token adds nothing for a deliberately session-less public link.

- `bootstrap/app.php` — added `'task/*', '*/task/*'` to the CSRF `except` list
  (covers both the custom domain `task/...` and the platform slug `{shop}/task/...`).

Plus a hardening tweak so the two domains never get crossed mid-flow:

- `resources/views/jobs-list.blade.php` — the per-task link is now **relative**
  (`/task/{nonce}`), so it stays on whatever host the jobs list was opened on
  (familyshoppers.net) instead of being rebuilt against the app host.

## Files changed
- app/Console/Commands/TaskReminderCommand.php
- bootstrap/app.php
- resources/views/jobs-list.blade.php

## Deploy
Copy the three files over the same paths and clear caches:

    php artisan config:clear
    php artisan route:clear
    php artisan view:clear

No migration, no data change. Reversible by restoring the three files.
